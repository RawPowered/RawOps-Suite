import React, { useState } from 'react';
import { PRODUCTS } from '../constants';
import type { Product } from '../types';

export const ProductCard: React.FC<{ product: Product }> = ({ product }) => {
  return (
    <div className="group relative bg-white rounded-lg overflow-hidden border border-gray-200 hover:shadow-2xl hover:-translate-y-1 transition-all duration-300 flex flex-col">
      <div className="aspect-w-1 aspect-h-1 w-full overflow-hidden relative">
        <a href="#" aria-label={`View details for ${product.name}`}>
          <img
            src={product.imageUrl}
            alt={product.name}
            className="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-300"
          />
        </a>
      </div>
      <div className="p-4 flex flex-col flex-grow">
        <div className="flex-grow">
          <span className="inline-flex items-center px-3 py-1 text-xs font-medium text-green-800 bg-green-100 rounded-full mb-2">
              <i className="fas fa-check-circle mr-2 text-green-600"></i>Vetted
          </span>
          <h3 className="text-lg font-semibold text-gray-900">
            <a href="#" className="hover:text-blue-600 transition-colors duration-200">
              {product.name}
            </a>
          </h3>
          <p className="text-sm text-gray-600 mt-1">{product.category}</p>
          <p className="text-sm text-gray-500 mt-2">{product.description}</p>
        </div>
        <div className="mt-4 flex items-center justify-between">
          <p className="text-2xl font-bold text-gray-900">${product.price.toFixed(2)}</p>
          <button className="bg-blue-600 text-white hover:bg-blue-700 w-10 h-10 rounded-full flex items-center justify-center transition-colors" aria-label="Add to cart">
            <i className="fas fa-plus"></i>
          </button>
        </div>
      </div>
    </div>
  );
};

const Products: React.FC = () => {
  const [currentPage, setCurrentPage] = useState(1);
  const PRODUCTS_PER_PAGE = 6;

  const indexOfLastProduct = currentPage * PRODUCTS_PER_PAGE;
  const indexOfFirstProduct = indexOfLastProduct - PRODUCTS_PER_PAGE;
  const currentProducts = PRODUCTS.slice(indexOfFirstProduct, indexOfLastProduct);

  const totalPages = Math.ceil(PRODUCTS.length / PRODUCTS_PER_PAGE);

  return (
    <section className="py-20 sm:py-24 bg-gray-50 border-t border-b border-gray-200">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center">
          <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Explore All Collections</h2>
          <p className="mt-4 max-w-2xl mx-auto text-lg text-gray-600">
            Every product is rigorously selected to match the high standards of our community.
          </p>
        </div>
        <div className="mt-12 grid grid-cols-1 gap-y-10 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-x-8">
          {currentProducts.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
        <div className="mt-16 flex justify-center items-center space-x-1 sm:space-x-2">
          {totalPages > 1 && Array.from({ length: totalPages }, (_, i) => i + 1).map(pageNumber => (
            <button
              key={pageNumber}
              onClick={() => setCurrentPage(pageNumber)}
              className={`w-10 h-10 sm:w-12 sm:h-12 rounded-full text-sm font-medium ${
                currentPage === pageNumber
                  ? 'bg-blue-600 text-white shadow-md'
                  : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-100'
              } transition-all duration-200`}
            >
              {pageNumber}
            </button>
          ))}
           <a href="#" className="px-4 h-12 inline-flex items-center rounded-full font-medium bg-white text-gray-800 border border-gray-300 hover:bg-gray-100 transition-colors text-sm">
            Browse All
          </a>
        </div>
      </div>
    </section>
  );
};

export default Products;