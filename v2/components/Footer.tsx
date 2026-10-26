import React from 'react';

const Footer: React.FC = () => {
  return (
    <footer className="bg-gray-900 text-gray-400">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
          <div className="col-span-2 md:col-span-1">
            <h3 className="text-xl font-bold text-blue-500">Product</h3>
            <p className="mt-2 text-sm">The ecosystem for the ambitious.</p>
          </div>
          <div>
            <h4 className="font-semibold text-white">Shop</h4>
            <ul className="mt-4 space-y-2 text-sm">
              <li><a href="#" className="text-gray-400 hover:text-white">Apparel</a></li>
              <li><a href="#" className="text-gray-400 hover:text-white">Nutrition</a></li>
              <li><a href="#" className="text-gray-400 hover:text-white">Supplements</a></li>
              <li><a href="#" className="text-gray-400 hover:text-white">Accessories</a></li>
            </ul>
          </div>
          <div>
            <h4 className="font-semibold text-white">For Partners</h4>
            <ul className="mt-4 space-y-2 text-sm">
              <li><a href="#" className="text-gray-400 hover:text-white">Sell on Product</a></li>
              <li><a href="#" className="text-gray-400 hover:text-white">Affiliate Program</a></li>
              <li><a href="#" className="text-gray-400 hover:text-white">Careers</a></li>
              <li><a href="#vetting" className="text-gray-400 hover:text-white">Our Standards</a></li>
            </ul>
          </div>
          <div>
            <h4 className="font-semibold text-white">Legal</h4>
            <ul className="mt-4 space-y-2 text-sm">
              <li><a href="#" className="text-gray-400 hover:text-white">Terms of Service</a></li>
              <li><a href="#" className="text-gray-400 hover:text-white">Privacy Policy</a></li>
            </ul>
          </div>
        </div>
        <div className="mt-12 pt-8 border-t border-gray-800 flex flex-col md:flex-row justify-between items-center">
          <p className="text-sm text-gray-500">&copy; {new Date().getFullYear()} Product. All rights reserved.</p>
          <div className="flex space-x-6 mt-4 md:mt-0">
            <a href="#" className="text-gray-400 hover:text-white"><i className="fab fa-tiktok text-xl"></i></a>
            <a href="#" className="text-gray-400 hover:text-white"><i className="fab fa-instagram text-xl"></i></a>
            <a href="#" className="text-gray-400 hover:text-white"><i className="fab fa-youtube text-xl"></i></a>
            <a href="#" className="text-gray-400 hover:text-white"><i className="fab fa-twitter text-xl"></i></a>
          </div>
        </div>
      </div>
    </footer>
  );
};

export default Footer;